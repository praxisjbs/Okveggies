/**
 * assets/js/admin-kitchen-runs.js
 * -----------------------------------------------------------------------------
 * OK Veggies. Progressive enhancement for the Kitchen Run quote workshop.
 *
 * The quote form is a plain HTML form and posts natively with JavaScript off:
 * the lines the server rendered can still be edited and priced, which is enough
 * for a list that arrived as catalogue or typed items. This file adds the two
 * things transcribing an uploaded list needs, plus the arithmetic a person
 * should not be doing in their head:
 *
 *   1. Add and remove lines, so a photographed list of 14 items becomes 14
 *      lines rather than the one placeholder it arrived as.
 *   2. A running total against the spend cap, so a colleague sees a quote go
 *      over the cap before they send it and the server refuses it.
 *
 * Values are written with textContent, never innerHTML.
 * -----------------------------------------------------------------------------
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-kr-admin-form]');
  if (!form) { return; }

  var host = form.querySelector('[data-kr-admin-rows]');
  var addButton = form.querySelector('[data-kr-admin-add]');
  var totalNote = form.querySelector('[data-kr-admin-total]');
  if (!host) { return; }

  var MAX_ROWS = 100;

  function rows() {
    return Array.prototype.slice.call(host.querySelectorAll('[data-kr-admin-row]'));
  }

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

  /** Field names carry their index, so a cloned line has to be renumbered. */
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

    var removable = rows().length > 1;
    rows().forEach(function (row) {
      var remove = row.querySelector('[data-kr-admin-remove]');
      if (remove) { remove.hidden = !removable; }
    });
    if (addButton) { addButton.hidden = rows().length >= MAX_ROWS; }
  }

  function recalculate() {
    if (!totalNote) { return; }

    var total = 0;
    var counted = 0;
    var incomplete = 0;

    rows().forEach(function (row) {
      var name = row.querySelector('input[name*="[item_name]"]');
      if (!name || String(name.value || '').trim() === '') { return; }

      var quantity = toQuantity((row.querySelector('input[name*="[quantity]"]') || {}).value);
      var price = toKobo((row.querySelector('input[name*="[unit_price]"]') || {}).value);
      if (quantity === null || price === null) { incomplete++; return; }

      counted++;
      total += Math.round(quantity * price);
    });

    if (counted === 0 && incomplete === 0) {
      totalNote.hidden = true;
      totalNote.textContent = '';
      return;
    }

    var text = counted + (counted === 1 ? ' line priced, ' : ' lines priced, ') + formatNaira(total) + '.';
    if (incomplete > 0) {
      text += ' ' + incomplete + (incomplete === 1 ? ' line still needs' : ' lines still need')
        + ' a quantity, a unit and a price.';
    }

    var cap = parseInt(form.getAttribute('data-spend-cap') || '', 10);
    if (!isNaN(cap) && total > cap) {
      text += ' This is over the agreed cap of ' + formatNaira(cap) + ', so it will be refused.';
    }

    totalNote.hidden = false;
    totalNote.textContent = text;
  }

  function addRow() {
    var all = rows();
    if (all.length >= MAX_ROWS) { return; }
    var clone = all[all.length - 1].cloneNode(true);
    clone.querySelectorAll('input').forEach(function (field) { field.value = ''; });
    clone.querySelectorAll('select').forEach(function (field) { field.selectedIndex = 0; });
    host.appendChild(clone);
    renumber();
    var name = clone.querySelector('input[name*="[item_name]"]');
    if (name) { name.focus(); }
    recalculate();
  }

  if (addButton) {
    addButton.hidden = false;
    addButton.addEventListener('click', addRow);
  }
  host.addEventListener('click', function (event) {
    var remove = event.target.closest('[data-kr-admin-remove]');
    if (!remove || rows().length <= 1) { return; }
    remove.closest('[data-kr-admin-row]').remove();
    renumber();
    recalculate();
  });
  host.addEventListener('input', recalculate);
  host.addEventListener('change', recalculate);

  renumber();
  recalculate();
}());
