/**
 * assets/js/admin-expenses.js
 * OK Veggies. The Expense module screen: the slide-up entry sheet, the supplier
 * typeahead, recording an expense without a page reload, voiding one, and the
 * info buttons that hide the words.
 *
 * Every write re-posts to /api/v1/expenses.php, which re-checks the permission
 * and the CSRF token on the server. User data goes into the DOM as text only,
 * never as raw HTML. Amounts are formatted by the server and read back; the one
 * place the client formats naira is the KPI strip, which mirrors Money.
 */
(function () {
  'use strict';

  var ENDPOINT = '/api/v1/expenses.php';

  // The category tint classes, mirrored from admin/expenses.php so a row this
  // file builds reads the same as one the server rendered. Literal strings, so
  // Tailwind compiles them from here too.
  var PILL = {
    forest:  'bg-forest-tint text-forest',
    foliage: 'bg-foliage-tint text-forest',
    gold:    'bg-gold-tint2 text-gold-ink',
    tomato:  'bg-tomato-tint text-tomato',
    clay:    'bg-clay-tint text-clay-ink',
    ink:     'bg-mist text-ink-60'
  };
  var TRASH = 'M5 7h14M10 7V5h4v2M6 7l1 12h10l1-12';

  function csrf() {
    return (window.OKV && window.OKV.csrf) ? window.OKV.csrf : '';
  }

  /** Format integer kobo the way Money does, for the KPI strip only. */
  function money(subunit) {
    var amount = Math.trunc(Number(subunit) || 0);
    var negative = amount < 0;
    var abs = Math.abs(amount);
    var naira = Math.floor(abs / 100);
    var kobo = abs % 100;
    var label = '₦' + naira.toLocaleString('en-NG');
    if (kobo !== 0) { label += '.' + String(kobo).padStart(2, '0'); }
    return (negative ? '-' : '') + label;
  }

  function el(tag, className, textValue) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (textValue != null) { node.textContent = textValue; }
    return node;
  }

  function svgTrash() {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('width', '20'); svg.setAttribute('height', '20');
    svg.setAttribute('fill', 'none'); svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2'); svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round'); svg.setAttribute('aria-hidden', 'true');
    var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', TRASH);
    svg.appendChild(path);
    return svg;
  }

  function post(params) {
    params.okv_csrf = csrf();
    return window.fetch(ENDPOINT, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
      body: new URLSearchParams(params)
    }).then(function (res) {
      return res.json().then(function (data) { return { ok: res.ok, data: data }; });
    });
  }

  // ---- Info buttons: a tap reveals one line, another hides it ---------------
  function bindInfo() {
    document.addEventListener('click', function (event) {
      var btn = event.target.closest ? event.target.closest('[data-info-toggle]') : null;
      if (!btn) { return; }
      var panel = document.getElementById(btn.getAttribute('aria-controls'));
      if (!panel) { return; }
      var open = panel.hidden;
      panel.hidden = !open;
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // ---- The entry sheet ------------------------------------------------------
  function sheetController() {
    var backdrop = document.querySelector('[data-expense-sheet]');
    if (!backdrop) { return null; }
    var form   = backdrop.querySelector('[data-expense-form]');
    var amount = backdrop.querySelector('[data-expense-amount]');
    var opener = null;

    function open(from) {
      opener = from || null;
      backdrop.hidden = false;
      document.documentElement.classList.add('overflow-hidden');
      window.setTimeout(function () { if (amount) { amount.focus(); } }, 20);
    }

    function close() {
      backdrop.hidden = true;
      document.documentElement.classList.remove('overflow-hidden');
      clearError();
      if (opener && opener.focus) { opener.focus(); }
    }

    function clearError() {
      var box = form.querySelector('[data-expense-error]');
      if (box) { box.hidden = true; box.textContent = ''; }
    }

    function showError(message) {
      var box = form.querySelector('[data-expense-error]');
      if (box) { box.textContent = message; box.hidden = false; }
    }

    backdrop.addEventListener('click', function (event) {
      if (event.target === backdrop) { close(); }
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !backdrop.hidden) { close(); }
    });
    Array.prototype.forEach.call(backdrop.querySelectorAll('[data-expense-close]'), function (b) {
      b.addEventListener('click', close);
    });

    return { backdrop: backdrop, form: form, amount: amount, open: open, close: close, error: showError, clearError: clearError };
  }

  // ---- Supplier typeahead ---------------------------------------------------
  function bindSupplier(form) {
    var input = form.querySelector('[data-expense-supplier]');
    var list  = form.querySelector('[data-expense-supplier-list]');
    if (!input || !list) { return; }
    var timer = null;

    function closeList() {
      list.hidden = true;
      list.textContent = '';
      input.setAttribute('aria-expanded', 'false');
    }

    function render(names) {
      list.textContent = '';
      if (!names.length) { closeList(); return; }
      names.forEach(function (name) {
        var li = el('li', 'okv-zone-option', name);
        li.setAttribute('role', 'option');
        li.addEventListener('mousedown', function (event) {
          event.preventDefault();
          input.value = name;
          closeList();
        });
        list.appendChild(li);
      });
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }

    input.addEventListener('input', function () {
      var q = input.value.trim();
      window.clearTimeout(timer);
      if (q.length < 1) { closeList(); return; }
      timer = window.setTimeout(function () {
        window.fetch(ENDPOINT + '?action=supplier_suggest&q=' + encodeURIComponent(q), {
          credentials: 'same-origin', headers: { 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); }).then(function (data) {
          render((data && data.suppliers) ? data.suppliers : []);
        }).catch(function () { closeList(); });
      }, 160);
    });
    input.addEventListener('blur', function () { window.setTimeout(closeList, 120); });
  }

  // ---- Build a row for an expense just recorded -----------------------------
  function buildRow(info) {
    var li = el('li', 'okv-card flex items-center gap-3');
    li.setAttribute('data-expense-row', '');
    li.dataset.id = String(info.id);
    li.dataset.amount = String(info.amountSubunit);
    li.dataset.kind = info.kind;

    var badge = el('span', 'okv-badge ' + (PILL[info.colour] || PILL.ink) + ' flex-none', info.categoryName);

    var mid = el('div', 'min-w-0 flex-1');
    mid.appendChild(el('p', 'truncate font-medium text-ink', info.primary));
    mid.appendChild(el('p', 'font-mono text-okv-micro text-ink-40', info.dateLabel));

    var amount = el('span', 'font-mono font-bold text-ink', info.amountDisplay);

    var voidBtn = el('button', 'okv-info-btn border-tomato/40 text-tomato hover:border-tomato hover:text-tomato');
    voidBtn.type = 'button';
    voidBtn.setAttribute('data-expense-void', '');
    voidBtn.dataset.id = String(info.id);
    voidBtn.setAttribute('aria-label', 'Void this expense');
    voidBtn.appendChild(svgTrash());

    li.appendChild(badge);
    li.appendChild(mid);
    li.appendChild(amount);
    li.appendChild(voidBtn);
    return li;
  }

  function dateLabelFrom(value) {
    var d = value ? new Date(value + 'T00:00:00') : new Date();
    if (isNaN(d.getTime())) { d = new Date(); }
    return d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
  }

  // ---- The KPI strip --------------------------------------------------------
  function bumpKpi(amountSubunit, kind) {
    var strip = document.querySelector('[data-expense-kpi]');
    if (!strip) { return; }
    var total = Number(strip.dataset.total || 0) + amountSubunit;
    var cog = Number(strip.dataset.cog || 0) + (kind === 'cost_of_goods' ? amountSubunit : 0);
    var op  = Number(strip.dataset.op || 0) + (kind === 'cost_of_goods' ? 0 : amountSubunit);
    strip.dataset.total = String(total);
    strip.dataset.cog = String(cog);
    strip.dataset.op = String(op);
    setKpi(strip, 'total', total);
    setKpi(strip, 'cog', cog);
    setKpi(strip, 'op', op);
  }

  function setKpi(strip, key, value) {
    var node = strip.querySelector('[data-kpi="' + key + '"]');
    if (node) { node.textContent = money(value); }
  }

  // ---- Record an expense ----------------------------------------------------
  function bindCreate(sheet) {
    var form = sheet.form;
    var another = false;
    Array.prototype.forEach.call(form.querySelectorAll('[data-expense-save-another]'), function (b) {
      b.addEventListener('click', function () { another = true; });
    });
    form.querySelectorAll('[data-expense-save]').forEach(function (b) {
      b.addEventListener('click', function () { another = false; });
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      sheet.clearError();
      var keepOpen = another;
      another = false;

      var chosen = form.querySelector('input[name="category_slug"]:checked');
      if (!chosen) { sheet.error('Choose a category for this expense.'); return; }

      var params = {
        action: 'create',
        amount: (form.querySelector('[name="amount"]') || {}).value || '',
        category_slug: chosen.value,
        supplier_name: (form.querySelector('[name="supplier_name"]') || {}).value || '',
        spent_on: (form.querySelector('[name="spent_on"]') || {}).value || '',
        quantity: (form.querySelector('[name="quantity"]') || {}).value || '',
        unit_cost: (form.querySelector('[name="unit_cost"]') || {}).value || '',
        description: (form.querySelector('[name="description"]') || {}).value || ''
      };

      var saveButtons = form.querySelectorAll('button[type="submit"]');
      saveButtons.forEach(function (b) { b.disabled = true; });

      post(params).then(function (result) {
        if (!result.ok || !result.data || result.data.status !== 'ok') {
          sheet.error((result.data && result.data.message) ? result.data.message : 'We could not save that expense.');
          return;
        }
        var note = params.description.trim();
        var supplier = params.supplier_name.trim();
        var info = {
          id: result.data.id,
          amountSubunit: Number(result.data.amount_subunit),
          amountDisplay: result.data.amount_display,
          kind: chosen.dataset.kind || 'operating',
          colour: chosen.dataset.colour || 'ink',
          categoryName: chosen.dataset.name || chosen.value,
          primary: supplier || note || (chosen.dataset.name || chosen.value),
          dateLabel: dateLabelFrom(params.spent_on)
        };
        addRowToList(info);
        bumpKpi(info.amountSubunit, info.kind);
        resetForm(form, keepOpen);
        if (keepOpen) {
          if (sheet.amount) { sheet.amount.focus(); }
        } else {
          sheet.close();
        }
      }).catch(function () {
        sheet.error('Something went wrong. Please try again.');
      }).finally(function () {
        saveButtons.forEach(function (b) { b.disabled = false; });
      });
    });
  }

  function resetForm(form, keepCategory) {
    ['amount', 'quantity', 'unit_cost', 'description'].forEach(function (name) {
      var field = form.querySelector('[name="' + name + '"]');
      if (field) { field.value = ''; }
    });
    // The supplier and the date are smart defaults: keep them for the next one.
    if (!keepCategory) {
      var checked = form.querySelector('input[name="category_slug"]:checked');
      if (checked) { checked.checked = false; }
    }
  }

  function addRowToList(info) {
    var section = document.querySelector('[data-expense-list]');
    if (!section) { return; }
    var rows = section.querySelector('[data-expense-rows]');
    if (!rows) {
      // The list was empty; replace the empty state with a fresh list.
      section.textContent = '';
      rows = el('ul', 'space-y-2');
      rows.setAttribute('data-expense-rows', '');
      section.appendChild(rows);
    }
    var row = buildRow(info);
    row.classList.add('animate-okv-pop');
    rows.insertBefore(row, rows.firstChild);
  }

  // ---- Void an expense (inline confirm, then post) --------------------------
  function bindVoid() {
    document.addEventListener('click', function (event) {
      var btn = event.target.closest ? event.target.closest('[data-expense-void]') : null;
      if (!btn) { return; }
      var row = btn.closest('[data-expense-row]');
      if (!row || row.dataset.confirming === '1') { return; }
      row.dataset.confirming = '1';

      var original = btn.cloneNode(true);
      var confirm = el('button', 'okv-btn-danger min-h-[44px] px-3 text-xs', 'Void?');
      confirm.type = 'button';
      var cancel = el('button', 'okv-btn-text px-2 text-xs', 'Keep');
      cancel.type = 'button';
      var wrap = el('span', 'flex flex-none items-center gap-1');
      wrap.appendChild(confirm);
      wrap.appendChild(cancel);
      btn.replaceWith(wrap);

      cancel.addEventListener('click', function () {
        wrap.replaceWith(original);
        row.dataset.confirming = '';
      });
      confirm.addEventListener('click', function () {
        confirm.disabled = true;
        post({ action: 'void', id: row.dataset.id, reason: '' }).then(function (result) {
          if (!result.ok || !result.data || result.data.status !== 'ok') {
            wrap.replaceWith(original);
            row.dataset.confirming = '';
            return;
          }
          bumpKpi(-Number(row.dataset.amount || 0), row.dataset.kind || 'operating');
          row.style.transition = 'opacity 240ms';
          row.style.opacity = '0';
          window.setTimeout(function () { row.remove(); }, 240);
        }).catch(function () {
          wrap.replaceWith(original);
          row.dataset.confirming = '';
        });
      });
    });
  }

  function bindOpeners(sheet) {
    Array.prototype.forEach.call(document.querySelectorAll('[data-expense-add-open]'), function (b) {
      b.addEventListener('click', function () { sheet.open(b); });
    });
  }

  function init() {
    bindInfo();
    var sheet = sheetController();
    if (sheet) {
      bindOpeners(sheet);
      bindSupplier(sheet.form);
      bindCreate(sheet);
    }
    bindVoid();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
