/**
 * assets/js/admin-order-new.js
 * -----------------------------------------------------------------------------
 * OK Veggies. Progressive enhancement for the phone-order screen.
 *
 * The screen is a plain HTML form and works with JavaScript switched off: the
 * customer is found by a GET search, the eight line slots post natively, and
 * the server prices every catalogue line itself. Nothing here is required to
 * take an order.
 *
 * What this adds is what a person should not be doing while somebody waits on
 * the phone:
 *
 *   1. The typed-item fields only appear on a line that is actually typed in,
 *      instead of eight rows of boxes nobody needs.
 *   2. The catalogue price drops into the price box when an item is chosen, so
 *      a colleague can see it, read it out, and change it if they agreed
 *      something else. Left alone, the server uses its own price anyway.
 *   3. A running line total and order total, because reading a total back to a
 *      customer is the moment they say yes.
 *   4. More lines than the eight the server drew.
 *
 * Finding the caller is admin-customer-picker.js, shared with the typed-in
 * kitchen list screen.
 *
 * The arithmetic here is for the eye only. Every figure that reaches the
 * database is summed again on the server from prices the server read itself.
 *
 * Values are written with textContent, never innerHTML.
 * -----------------------------------------------------------------------------
 */
(function () {
  'use strict';

  var page = document.querySelector('[data-order-builder]');
  if (!page) { return; }

  var MAX_ROWS = 100;

  function toKobo(text) {
    var cleaned = String(text == null ? '' : text).replace(/[,\s₦]/g, '');
    if (cleaned === '' || !/^\d+(\.\d{1,2})?$/.test(cleaned)) { return null; }
    return Math.round(parseFloat(cleaned) * 100);
  }

  function toQuantity(text) {
    var cleaned = String(text == null ? '' : text).trim();
    if (cleaned === '' || !/^\d+(\.\d{1,3})?$/.test(cleaned)) { return null; }
    var value = parseFloat(cleaned);
    return value > 0 ? value : null;
  }

  function formatNaira(kobo) {
    var naira = Math.round(kobo) / 100;
    return '₦' + naira.toLocaleString('en-NG', {
      minimumFractionDigits: naira % 1 === 0 ? 0 : 2,
      maximumFractionDigits: 2
    });
  }

  // ---------------------------------------------------------------------------
  // The line editor. Customer search is admin-customer-picker.js, shared with
  // the typed-in kitchen list screen.
  // ---------------------------------------------------------------------------
  var form = page.querySelector('[data-order-form]');
  if (!form) { return; }

  var host       = form.querySelector('[data-line-rows]');
  var addButton  = form.querySelector('[data-add-line]');
  var totalNote  = form.querySelector('[data-order-total]');
  if (!host) { return; }

  function rows() {
    return Array.prototype.slice.call(host.querySelectorAll('[data-line-row]'));
  }

  /** Show the typed-item boxes only on a line that is actually typed in. */
  function showFields(row) {
    var chosen = row.querySelector('[data-line-item]');
    var custom = chosen && chosen.value === 'custom';
    row.querySelectorAll('[data-line-field="custom"]').forEach(function (field) {
      field.hidden = !custom;
    });
  }

  /**
   * Put our price in the box when an item is chosen, so a colleague can read it
   * out and change it if they agreed something else. An empty box means "our
   * price" to the server, and the server reads it itself either way.
   */
  function fillPrice(row) {
    var chosen = row.querySelector('[data-line-item]');
    var price  = row.querySelector('[data-line-price]');
    if (!chosen || !price) { return; }

    var option = chosen.options[chosen.selectedIndex];
    var listed = option ? option.getAttribute('data-price') : null;
    price.value = listed ? listed : '';

    var quantity = row.querySelector('[data-line-quantity]');
    if (quantity && listed && quantity.value.trim() === '') { quantity.value = '1'; }
  }

  function recalculate() {
    var total = 0;

    rows().forEach(function (row) {
      var note     = row.querySelector('[data-line-total]');
      var chosen   = row.querySelector('[data-line-item]');
      var quantity = toQuantity((row.querySelector('[data-line-quantity]') || {}).value);
      var price    = toKobo((row.querySelector('[data-line-price]') || {}).value);

      if (!chosen || chosen.value === '' || quantity === null || price === null) {
        if (note) { note.textContent = ''; }
        return;
      }

      var line = Math.round(quantity * price);
      total += line;
      if (note) { note.textContent = formatNaira(line); }
    });

    if (totalNote) { totalNote.textContent = formatNaira(total); }
  }

  /** Field names carry their index, so a cloned line has to be renumbered. */
  function renumber() {
    rows().forEach(function (row, index) {
      row.querySelectorAll('[name]').forEach(function (field) {
        field.name = field.name.replace(/lines\[\d+\]/, 'lines[' + index + ']');
      });
      row.querySelectorAll('[id]').forEach(function (field) {
        var fresh = field.id.replace(/line-\d+-/, 'line-' + index + '-');
        var label = row.querySelector('label[for="' + field.id + '"]');
        field.id = fresh;
        if (label) { label.setAttribute('for', fresh); }
      });
      var legend = row.querySelector('legend');
      if (legend) { legend.textContent = 'Line ' + (index + 1); }
    });
  }

  host.addEventListener('change', function (event) {
    var row = event.target.closest('[data-line-row]');
    if (!row) { return; }
    if (event.target.matches('[data-line-item]')) {
      showFields(row);
      fillPrice(row);
    }
    recalculate();
  });

  host.addEventListener('input', function (event) {
    if (event.target.closest('[data-line-row]')) { recalculate(); }
  });

  if (addButton) {
    addButton.hidden = false;
    addButton.classList.remove('hidden');
    addButton.addEventListener('click', function () {
      var all = rows();
      if (all.length >= MAX_ROWS) { return; }

      var fresh = all[all.length - 1].cloneNode(true);
      fresh.querySelectorAll('input').forEach(function (field) { field.value = ''; });
      fresh.querySelectorAll('select').forEach(function (field) { field.selectedIndex = 0; });
      var note = fresh.querySelector('[data-line-total]');
      if (note) { note.textContent = ''; }

      host.appendChild(fresh);
      renumber();
      showFields(fresh);

      var first = fresh.querySelector('[data-line-item]');
      if (first) { first.focus(); }
    });
  }

  rows().forEach(showFields);
  recalculate();
})();
