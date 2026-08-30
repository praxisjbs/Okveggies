/**
 * assets/js/admin-combos.js
 * OK Veggies. The combo builder. Forms marked data-combo-form post to
 * api/v1/combos.php by fetch. Component add, edit and remove refresh the total
 * and the component list in place, so a Manager watching the loss-making flag
 * sees it move the moment the maths does. Everything else (details, sell price,
 * publish, image, delete) reloads on success so a link the panel points at
 * (photo, history opener) picks up the new server truth.
 *
 * Without JavaScript the panel still renders and every form still posts, because
 * each one carries its own action and CSRF token. The server re-checks every
 * permission regardless. User data is written with textContent, never innerHTML.
 */
(function () {
  'use strict';

  var ENDPOINT = '/api/v1/combos.php';

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

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined && text !== null) { node.textContent = String(text); }
    return node;
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

  function clearFieldErrors(form) {
    form.querySelectorAll('[data-field-error]').forEach(function (node) { node.remove(); });
    form.querySelectorAll('.okv-input').forEach(function (input) {
      input.removeAttribute('aria-invalid');
      input.classList.remove('border-tomato');
    });
  }

  function showFieldErrors(form, errors) {
    Object.keys(errors).forEach(function (name) {
      var field = form.querySelector('[name="' + name + '"]');
      if (!field) { return; }
      field.setAttribute('aria-invalid', 'true');
      field.classList.add('border-tomato');
      var note = el('p', 'text-xs text-tomato mt-1', errors[name]);
      note.setAttribute('data-field-error', '');
      if (field.parentNode) { field.parentNode.appendChild(note); }
    });
    var first = form.querySelector('[aria-invalid="true"]');
    if (first) { first.focus(); }
  }

  // --- Panel refresh (in-place) ---------------------------------------------

  /** Turn a subunit amount into a "₦8,000" text. Same shape as Money::format. */
  function naira(subunit) {
    if (subunit === null || subunit === undefined || subunit === '') { return ''; }
    if (window.OKV && OKV.money) { return OKV.money(subunit); }
    var kobo = Math.abs(subunit) % 100;
    var naira = Math.floor(Math.abs(subunit) / 100).toLocaleString('en-NG');
    return (subunit < 0 ? '-' : '') + '₦' + naira + (kobo ? '.' + String(kobo).padStart(2, '0') : '');
  }

  /** Options for the per-component unit picker, read once from the add-a-component form. */
  function unitOptionsFor(panel) {
    var seed = panel.querySelector('[data-component-add] select[name="unit_id"]');
    if (!seed) { return []; }
    return Array.prototype.map.call(seed.options, function (option) {
      return { id: option.value, label: option.textContent };
    });
  }

  /** Redraw the component list from a fresh payload. */
  function renderComponents(panel, components, csrf) {
    var container = panel.querySelector('[data-combo-components]');
    if (!container) { return; }
    var comboId = panel.getAttribute('data-combo-id') || '';
    var unitOptions = unitOptionsFor(panel);

    container.textContent = '';
    if (!components || !components.length) {
      container.appendChild(el('p', 'text-sm text-ink-60', 'No components yet. Add the first one below.'));
      return;
    }

    components.forEach(function (component) {
      var componentId = String(component.component_id);
      var form = document.createElement('form');
      form.action = ENDPOINT;
      form.method = 'POST';
      form.className = 'grid grid-cols-[1fr_auto_auto_auto] items-end gap-2 rounded-md border border-mist px-3 py-2';
      form.setAttribute('data-component-form', '');
      form.setAttribute('data-component-id', componentId);

      var csrfInput = document.createElement('input');
      csrfInput.type = 'hidden';
      csrfInput.name = 'okv_csrf';
      csrfInput.value = csrf;
      form.appendChild(csrfInput);

      ['action=update_component', 'combo_id=' + comboId, 'component_id=' + componentId].forEach(function (pair) {
        var parts = pair.split('=');
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = parts[0];
        hidden.value = parts[1];
        form.appendChild(hidden);
      });

      var info = el('div', 'min-w-0');
      info.appendChild(el('p', 'text-sm font-medium text-ink truncate', component.product_name || ''));

      var meta = el('p', 'text-xs text-ink-40 font-mono');
      var unitPrice = naira(component.current_price_subunit || 0);
      meta.appendChild(document.createTextNode(
        (component.product_sku || '') + '. Current ' + unitPrice + '/' + (component.unit || '') + '. Line '
      ));
      var lineSpan = el('span', 'font-mono', naira(component.line_subunit || 0));
      lineSpan.setAttribute('data-component-line', '');
      meta.appendChild(lineSpan);
      meta.appendChild(document.createTextNode('. '));
      if (Number(component.product_is_active) !== 1) {
        var warning = el('span', 'text-tomato', 'This product is off the shop.');
        meta.appendChild(warning);
      }
      info.appendChild(meta);
      form.appendChild(info);

      var qtyWrap = el('div');
      var qtyLabel = document.createElement('label');
      qtyLabel.className = 'sr-only';
      qtyLabel.htmlFor = 'cq-' + componentId;
      qtyLabel.textContent = 'Quantity';
      var qtyInput = document.createElement('input');
      qtyInput.id = 'cq-' + componentId;
      qtyInput.name = 'quantity';
      qtyInput.type = 'text';
      qtyInput.inputMode = 'decimal';
      qtyInput.className = 'okv-input w-20 font-mono text-right';
      qtyInput.value = formatQuantity(component.quantity);
      qtyWrap.appendChild(qtyLabel);
      qtyWrap.appendChild(qtyInput);
      form.appendChild(qtyWrap);

      form.appendChild(el('div', 'text-sm text-ink-60', component.unit || ''));

      var buttons = el('div', 'flex gap-2');
      var save = el('button', 'okv-btn-outline px-3 text-xs', 'Save');
      save.type = 'submit';
      buttons.appendChild(save);
      var remove = el('button', 'okv-btn-outline px-3 text-xs border-tomato text-tomato hover:bg-tomato-tint', 'Remove');
      remove.type = 'button';
      remove.setAttribute('data-component-remove', '');
      remove.setAttribute('data-component-id', componentId);
      buttons.appendChild(remove);
      form.appendChild(buttons);

      container.appendChild(form);
      wireComponentForm(form);
      wireComponentRemove(remove, form, panel);
    });
  }

  /** Strip trailing zeroes from a stored decimal, matching okv_quantity(). */
  function formatQuantity(value) {
    if (value === null || value === undefined || value === '') { return ''; }
    var num = Number(value);
    if (!isFinite(num)) { return String(value); }
    var text = num.toFixed(3);
    return text.replace(/0+$/, '').replace(/\.$/, '');
  }

  /** Refresh the header, price, flags and component list from a payload. */
  function refreshPanel(panel, payload, csrf) {
    if (!panel || !payload || !payload.combo) { return; }
    var combo = payload.combo;

    var name = panel.querySelector('[data-combo-name]');
    if (name) { name.textContent = combo.name || ''; }

    var price = panel.querySelector('[data-combo-price]');
    if (price) {
      price.textContent = '';
      if (payload.sell_price_formatted) {
        price.textContent = payload.sell_price_formatted;
      } else {
        price.appendChild(el('span', 'text-ink-40 font-sans text-sm', 'Not priced'));
      }
    }

    var total = panel.querySelector('[data-combo-total]');
    if (total) { total.textContent = payload.component_total_formatted || naira(payload.component_total || 0); }

    var flags = panel.querySelector('[data-combo-flags]');
    if (flags) {
      flags.textContent = '';
      if (payload.loss_making) {
        flags.appendChild(el('span', 'inline-flex items-center rounded-md bg-tomato-tint text-tomato text-xs font-semibold px-2 py-1', 'Selling below components'));
        flags.appendChild(el('p', 'text-xs text-tomato mt-1 font-mono',
          'Components ' + (payload.component_total_formatted || naira(payload.component_total)) +
          ', sell price ' + (payload.sell_price_formatted || naira(Number(combo.price_subunit) || 0))));
      } else if (payload.customer_saving && Number(payload.customer_saving) > 0) {
        var wrap = el('span', 'text-xs text-ink-60');
        wrap.appendChild(document.createTextNode('Customer saves '));
        wrap.appendChild(el('span', 'font-mono', payload.customer_saving_formatted || naira(payload.customer_saving)));
        wrap.appendChild(document.createTextNode(' against the components'));
        flags.appendChild(wrap);
      }
    }

    var activeBadge = panel.querySelector('[data-combo-active-badge]');
    if (activeBadge) {
      activeBadge.textContent = Number(combo.is_active) === 1 ? 'On the shop' : 'Off the shop';
      activeBadge.className = 'okv-badge ' + (Number(combo.is_active) === 1 ? 'okv-badge-available' : 'okv-badge-out');
    }
    var featured = panel.querySelector('[data-combo-featured-badge]');
    if (featured) { featured.hidden = Number(combo.is_featured) !== 1; }

    var publishAction = panel.querySelector('[data-combo-publish-action]');
    var publishButton = panel.querySelector('[data-combo-publish-button]');
    if (publishAction && publishButton) {
      if (Number(combo.is_active) === 1) {
        publishAction.value = 'unpublish';
        publishButton.textContent = 'Take off the shop';
        publishButton.className = 'okv-btn-outline px-6';
      } else {
        publishAction.value = 'publish';
        publishButton.textContent = 'Publish to the shop';
        publishButton.className = 'okv-btn px-6';
      }
    }

    if (payload.components) {
      renderComponents(panel, payload.components, csrf);
    }
  }

  // --- Component actions -----------------------------------------------------

  function wireComponentForm(form) {
    if (form._okvWired) { return; }
    form._okvWired = true;
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var button = form.querySelector('button[type="submit"]');
      if (button) { button.disabled = true; }

      send(new URLSearchParams(new FormData(form))).then(function (res) {
        if (button) { button.disabled = false; }
        if (res.ok && res.data.status === 'ok') {
          var panel = form.closest('[data-combo-panel]');
          refreshPanel(panel, res.data, csrfFrom(form));
          toast(res.data.message || 'Component saved.', 'ok');
          return;
        }
        toast(res.data.message || 'We could not update that component.', 'error');
      }).catch(function () {
        if (button) { button.disabled = false; }
        toast('We could not reach the server. Check your connection and try again.', 'error');
      });
    });
  }

  function wireComponentRemove(button, form, panel) {
    if (button._okvWired) { return; }
    button._okvWired = true;
    button.addEventListener('click', function () {
      if (!window.confirm('Remove this component from the combo?')) { return; }
      var componentId = button.getAttribute('data-component-id');
      var comboId = panel ? panel.getAttribute('data-combo-id') : '';
      var body = new URLSearchParams();
      body.set('action', 'remove_component');
      body.set('combo_id', comboId);
      body.set('component_id', componentId);
      body.set('okv_csrf', csrfFrom(form));
      button.disabled = true;
      send(body).then(function (res) {
        if (res.ok && res.data.status === 'ok') {
          refreshPanel(panel, res.data, csrfFrom(form));
          toast(res.data.message || 'Component removed.', 'ok');
          return;
        }
        button.disabled = false;
        toast(res.data.message || 'We could not remove that component.', 'error');
      }).catch(function () {
        button.disabled = false;
        toast('We could not reach the server. Check your connection and try again.', 'error');
      });
    });
  }

  function wireAddComponent(form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var errBox = form.querySelector('[data-okv-error]');
      var button = form.querySelector('button[type="submit"]');
      clearFieldErrors(form);
      if (errBox) { errBox.hidden = true; errBox.textContent = ''; }
      if (button) { button.disabled = true; }

      send(new URLSearchParams(new FormData(form))).then(function (res) {
        if (button) { button.disabled = false; }
        if (res.ok && res.data.status === 'ok') {
          var panel = form.closest('[data-combo-panel]');
          refreshPanel(panel, res.data, csrfFrom(form));
          form.reset();
          toast(res.data.message || 'Component added.', 'ok');
          return;
        }
        if (res.data.errors) { showFieldErrors(form, res.data.errors); }
        var message = res.data.message || 'We could not add that component.';
        if (errBox) { errBox.textContent = message; errBox.hidden = false; }
        else { toast(message, 'error'); }
      }).catch(function () {
        if (button) { button.disabled = false; }
        toast('We could not reach the server. Check your connection and try again.', 'error');
      });
    });
  }

  // --- Everything else (details, sell price, publish, window) ----------------

  function wireComboForm(form) {
    // The add-a-component form has its own handler that keeps the panel in
    // place. Everything else uses the generic combo form handler.
    if (form.hasAttribute('data-component-add')) { return; }

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
          if (form.hasAttribute('data-refresh-on-success')) {
            setTimeout(function () { window.location.reload(); }, 400);
            return;
          }
          var panel = form.closest('[data-combo-panel]');
          if (panel && res.data.combo) {
            refreshPanel(panel, res.data, csrfFrom(form));
            release();
          } else {
            setTimeout(function () { window.location.reload(); }, 400);
          }
          return;
        }
        release();
        if (res.data.errors) { showFieldErrors(form, res.data.errors); }
        var message = res.data.message || 'We could not save that. Please try again.';
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

  // --- Image upload ----------------------------------------------------------

  function wireImageForm(form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var button = form.querySelector('button[type="submit"]');
      if (button) { button.disabled = true; button.textContent = 'Uploading'; }

      var body = new FormData(form);
      send(body).then(function (res) {
        if (button) { button.disabled = false; button.textContent = 'Upload'; }
        if (res.ok && res.data.status === 'ok') {
          toast(res.data.message || 'Photo saved.', 'ok');
          setTimeout(function () { window.location.reload(); }, 400);
          return;
        }
        toast(res.data.message || 'We could not save that photo.', 'error');
      }).catch(function () {
        if (button) { button.disabled = false; button.textContent = 'Upload'; }
        toast('We could not reach the server. Check your connection and try again.', 'error');
      });
    });
  }

  // --- Delete ----------------------------------------------------------------

  function wireDelete(form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (!window.confirm('Remove this combo? If anything refers to it we will tell you instead, and you can take it off the shop.')) {
        return;
      }
      var button = form.querySelector('button[type="submit"]');
      if (button) { button.disabled = true; }

      send(new URLSearchParams(new FormData(form))).then(function (res) {
        if (res.ok && res.data.status === 'ok') {
          toast(res.data.message || 'Combo removed.', 'ok');
          setTimeout(function () { window.location.reload(); }, 400);
          return;
        }
        if (button) { button.disabled = false; }
        toast(res.data.message || 'We could not remove that combo.', 'error');
      }).catch(function () {
        if (button) { button.disabled = false; }
        toast('We could not reach the server. Check your connection and try again.', 'error');
      });
    });
  }

  // --- Panel: open/close and unit auto-pick ---------------------------------

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

  /** When the Manager picks a product, prefill the unit to match its own. */
  function wireUnitAutoPick(productSelect, unitSelect) {
    if (!productSelect || !unitSelect) { return; }
    productSelect.addEventListener('change', function () {
      var option = productSelect.options[productSelect.selectedIndex];
      var unitId = option ? option.getAttribute('data-unit-id') : '';
      if (!unitId) { return; }
      Array.prototype.forEach.call(unitSelect.options, function (o) {
        if (o.value === unitId) { unitSelect.value = unitId; }
      });
    });
  }

  // --- Price history --------------------------------------------------------

  function wireHistory() {
    var panel = document.querySelector('[data-history-panel]');
    if (!panel) { return; }
    var body = panel.querySelector('[data-history-body]');
    var title = document.getElementById('combo-history-title');
    var close = panel.querySelector('[data-history-close]');
    var opener = null;

    function shut() {
      panel.hidden = true;
      if (opener) { opener.focus(); }
    }
    if (close) { close.addEventListener('click', shut); }
    panel.addEventListener('click', function (event) { if (event.target === panel) { shut(); } });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !panel.hidden) { shut(); }
    });

    document.querySelectorAll('[data-history-open]').forEach(function (button) {
      button.addEventListener('click', function () {
        opener = button;
        var id = button.getAttribute('data-combo-id');
        if (title) { title.textContent = 'Price history, ' + (button.getAttribute('data-combo-name') || ''); }
        if (body) { body.textContent = 'Loading.'; }
        panel.hidden = false;
        if (close) { close.focus(); }

        fetch(ENDPOINT + '?action=get&combo_id=' + encodeURIComponent(id), {
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
        }).then(function (res) { return res.json(); }).then(function (data) {
          if (!body) { return; }
          body.textContent = '';
          var rows = (data && data.history) || [];
          if (!rows.length) {
            body.appendChild(el('p', 'text-sm text-ink-60', 'This combo has no price history yet.'));
            return;
          }
          var list = el('ol', 'space-y-3');
          rows.forEach(function (row) {
            var item = el('li', 'border-l-2 border-mist pl-4');
            var line = el('p', 'text-sm text-ink');
            line.textContent = row.old_price_subunit === null || row.old_price_subunit === undefined
              ? 'Priced at ' + naira(row.new_price_subunit)
              : naira(row.old_price_subunit) + ' to ' + naira(row.new_price_subunit);
            item.appendChild(line);
            var meta = el('p', 'text-xs text-ink-40');
            meta.textContent = String(row.effective_from || '') +
              (row.changed_by_name ? ' by ' + row.changed_by_name : '');
            item.appendChild(meta);
            if (row.change_reason) {
              item.appendChild(el('p', 'text-xs text-ink-60 mt-1', row.change_reason));
            }
            list.appendChild(item);
          });
          body.appendChild(list);
        }).catch(function () {
          if (body) { body.textContent = 'We could not load the history. Please try again.'; }
        });
      });
    });
  }

  ready(function () {
    document.querySelectorAll('form[data-combo-form]').forEach(function (form) {
      if (form.hasAttribute('data-component-add')) { wireAddComponent(form); return; }
      wireComboForm(form);
    });
    document.querySelectorAll('form[data-component-form]').forEach(wireComponentForm);
    document.querySelectorAll('[data-component-remove]').forEach(function (button) {
      var form = button.closest('[data-component-form]');
      var panel = button.closest('[data-combo-panel]');
      wireComponentRemove(button, form, panel);
    });
    document.querySelectorAll('form[data-combo-image-form]').forEach(wireImageForm);
    document.querySelectorAll('form[data-combo-delete-form]').forEach(wireDelete);
    wirePanel('[data-add-open]', '[data-add-panel]', '[data-add-close]');
    wireHistory();

    // Add-a-combo: when the first product changes, prefill the unit.
    document.querySelectorAll('[data-add-panel]').forEach(function (panel) {
      wireUnitAutoPick(
        panel.querySelector('select[name="first_product_id"]'),
        panel.querySelector('select[name="first_unit_id"]')
      );
    });
    // Add-a-component: same behaviour, per combo panel.
    document.querySelectorAll('[data-component-add]').forEach(function (form) {
      wireUnitAutoPick(
        form.querySelector('select[name="product_id"]'),
        form.querySelector('select[name="unit_id"]')
      );
    });

    // Opened straight from a link (e.g. /admin/combos.php?combo=3): scroll it in.
    var params = new URLSearchParams(window.location.search);
    var wanted = params.get('combo');
    if (wanted) {
      var card = document.getElementById('combo-' + wanted);
      if (card) { card.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    }
  });
})();
