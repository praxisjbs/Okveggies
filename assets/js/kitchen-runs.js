/**
 * assets/js/kitchen-runs.js
 * -----------------------------------------------------------------------------
 * OK Veggies. Progressive enhancement for the Kitchen Run list form.
 *
 * The form works with JavaScript switched off: three rows are rendered by the
 * server and it posts natively. This file adds four things and nothing else.
 *
 *   1. Add and remove rows, so a long list is not three items and a shrug. A
 *      new row is a clone of the last one, so the markup only lives in
 *      includes/components/shop/kitchen_run_row.php.
 *   2. A running total, but only when every filled row carries its own price.
 *      A list we are pricing has no total to show yet and we do not invent one.
 *   3. The spend cap field appears only once open budget is ticked.
 *   4. The submit is sent with fetch so a refusal is a sentence under the
 *      button rather than a page the customer has to navigate back from.
 *
 * Every value shown is written with textContent, never innerHTML, so an item
 * name a customer typed can never become markup.
 * -----------------------------------------------------------------------------
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-kitchen-run-form]');
  if (!form) { return; }

  var rowsHost = form.querySelector('[data-kr-rows]');
  var addButton = form.querySelector('[data-kr-add]');
  var totalNote = form.querySelector('[data-kr-total]');
  var errorNote = form.querySelector('[data-kr-error]');
  var openBudget = form.querySelector('[data-kr-open]');
  var capField = form.querySelector('[data-kr-cap]');
  var start = form.getAttribute('data-start') || 'custom';
  var MAX_ROWS = 100;

  // --- Money -----------------------------------------------------------------

  /** Naira typed by a person into kobo, or null when it is not a number. */
  function toKobo(text) {
    var cleaned = String(text == null ? '' : text).replace(/[,\s₦]/g, '');
    if (cleaned === '' || !/^\d+(\.\d{1,2})?$/.test(cleaned)) { return null; }
    return Math.round(parseFloat(cleaned) * 100);
  }

  function formatNaira(kobo) {
    var naira = Math.round(kobo) / 100;
    return '₦' + naira.toLocaleString('en-NG', {
      minimumFractionDigits: naira % 1 === 0 ? 0 : 2,
      maximumFractionDigits: 2
    });
  }

  function toQuantity(text) {
    var cleaned = String(text == null ? '' : text).trim();
    if (cleaned === '' || !/^\d+(\.\d{1,3})?$/.test(cleaned)) { return null; }
    var value = parseFloat(cleaned);
    return value > 0 ? value : null;
  }

  // --- Rows ------------------------------------------------------------------

  function rows() {
    return Array.prototype.slice.call(rowsHost.querySelectorAll('[data-kr-row]'));
  }

  /** A row nobody has typed into is not an unfinished row, it is a spare one. */
  function isBlank(row) {
    var fields = row.querySelectorAll('input, select');
    for (var i = 0; i < fields.length; i++) {
      if (String(fields[i].value || '').trim() !== '') { return false; }
    }
    return true;
  }

  /**
   * Field names carry their row index, so a cloned row has to be renumbered or
   * PHP folds two rows into one.
   */
  function renumber() {
    rows().forEach(function (row, index) {
      row.querySelectorAll('[name]').forEach(function (field) {
        field.name = field.name.replace(/items\[\d+\]/, 'items[' + index + ']');
      });
      row.querySelectorAll('[id]').forEach(function (field) {
        var label = row.querySelector('label[for="' + field.id + '"]');
        var fresh = field.id.replace(/-\d+$/, '-' + index);
        field.id = fresh;
        if (label) { label.setAttribute('for', fresh); }
      });
    });
    updateControls();
  }

  function updateControls() {
    var all = rows();
    var removable = all.length > 1;
    all.forEach(function (row) {
      var remove = row.querySelector('[data-kr-remove]');
      if (remove) { remove.hidden = !removable; }
    });
    if (addButton) { addButton.hidden = all.length >= MAX_ROWS; }
  }

  function addRow() {
    var all = rows();
    if (all.length >= MAX_ROWS) { return; }
    var clone = all[all.length - 1].cloneNode(true);
    clone.querySelectorAll('input').forEach(function (field) { field.value = ''; });
    clone.querySelectorAll('select').forEach(function (field) { field.selectedIndex = 0; });
    rowsHost.appendChild(clone);
    renumber();
    var name = clone.querySelector('[data-kr-name]') || clone.querySelector('input');
    if (name) { name.focus(); }
    recalculate();
  }

  // --- The running total -----------------------------------------------------

  /**
   * Only shown when every row a customer has filled in carries both a quantity
   * and a price. A list we are pricing has no total yet, and showing a partial
   * one would be a number nobody agreed to.
   */
  function recalculate() {
    if (!totalNote) { return; }

    var total = 0;
    var filled = 0;
    var complete = true;

    rows().forEach(function (row) {
      if (isBlank(row)) { return; }
      filled++;

      var priceField = row.querySelector('[data-kr-price]');
      var product = row.querySelector('[data-kr-product]');
      var selected = product && product.selectedIndex > -1 ? product.options[product.selectedIndex] : null;
      var catalogue = selected && selected.value ? parseInt(selected.getAttribute('data-price'), 10) : null;

      var price = catalogue !== null && !isNaN(catalogue) ? catalogue : toKobo(priceField ? priceField.value : '');
      var quantity = toQuantity((row.querySelector('[data-kr-qty]') || {}).value);

      if (price === null || quantity === null) { complete = false; return; }
      total += Math.round(price * quantity);
    });

    if (filled === 0 || !complete) {
      totalNote.hidden = true;
      totalNote.textContent = '';
      return;
    }
    totalNote.hidden = false;
    totalNote.textContent = filled + (filled === 1 ? ' item, ' : ' items, ') + formatNaira(total)
      + '. We confirm every price before anything is charged.';
  }

  // --- Submitting ------------------------------------------------------------

  function showError(text) {
    if (!errorNote) { return; }
    errorNote.textContent = text;
    errorNote.hidden = text === '';
    if (text !== '') { errorNote.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
  }

  function submit(event) {
    event.preventDefault();
    showError('');

    var button = form.querySelector('button[type="submit"]');
    if (button) { button.disabled = true; }

    fetch(form.action, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
      body: new FormData(form)
    }).then(function (response) {
      return response.json().then(function (body) { return { ok: response.ok, body: body }; });
    }).then(function (result) {
      if (result.ok && result.body && result.body.id) {
        window.location.href = '/kitchen-runs.php?request=' + encodeURIComponent(result.body.id) + '&submitted=1';
        return;
      }
      if (button) { button.disabled = false; }
      showError((result.body && result.body.message) || 'We could not send that list. Please try again.');
    }).catch(function () {
      if (button) { button.disabled = false; }
      showError('We could not reach the server. Check your connection and try again.');
    });
  }

  // --- Wiring ----------------------------------------------------------------

  if (rowsHost) {
    if (addButton) {
      addButton.hidden = false;
      addButton.addEventListener('click', addRow);
    }
    rowsHost.addEventListener('click', function (event) {
      var remove = event.target.closest('[data-kr-remove]');
      if (!remove || rows().length <= 1) { return; }
      remove.closest('[data-kr-row]').remove();
      renumber();
      recalculate();
    });
    rowsHost.addEventListener('input', recalculate);
    rowsHost.addEventListener('change', recalculate);
    renumber();
    recalculate();
  }

  if (openBudget && capField) {
    var syncCap = function () { capField.hidden = !openBudget.checked; };
    openBudget.addEventListener('change', syncCap);
    syncCap();
  }

  // Who prices it decides whether a price field is even a sensible question.
  if (start === 'custom') {
    form.querySelectorAll('[data-pricing]').forEach(function (radio) {
      radio.addEventListener('change', function () {
        var byUs = form.querySelector('[data-pricing][value="by_us"]');
        var hide = byUs ? byUs.checked : false;
        form.querySelectorAll('[data-kr-price-field]').forEach(function (field) { field.hidden = hide; });
        recalculate();
      });
    });
    var initial = form.querySelector('[data-pricing]:checked');
    if (initial && initial.value === 'by_us') {
      form.querySelectorAll('[data-kr-price-field]').forEach(function (field) { field.hidden = true; });
    }
  }

  form.addEventListener('submit', submit);
}());
