/**
 * assets/js/admin-kitchen-runs.js
 * -----------------------------------------------------------------------------
 * OK Veggies. Progressive enhancement for the Kitchen Run quote workshop.
 *
 * The quote form is a plain HTML form and posts natively with JavaScript off:
 * the lines the server rendered can still be edited and priced, which is enough
 * for a list that arrived as catalogue or typed items. This file adds what
 * transcribing an uploaded list needs, plus the arithmetic a person should not
 * be doing in their head:
 *
 *   1. Add and remove lines, so a photographed list of 14 items becomes 14
 *      lines rather than the one placeholder it arrived as.
 *   2. Move a line up or down, because a kitchen list has an order to it and a
 *      transcribed one rarely comes out in the order the customer wrote it.
 *      sort_order is written from the position in the posted array, so moving
 *      the rows before the form is submitted is the whole mechanism.
 *   3. A running total against the spend cap, so a colleague sees a quote go
 *      over the cap before they send it and the server refuses it.
 *
 * Without JavaScript the form still posts the lines in the order the server
 * rendered them, which is the order they are already stored in. Nothing here is
 * required for a quote to be sent.
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

    var all = rows();
    var removable = all.length > 1;
    all.forEach(function (row, index) {
      var remove = row.querySelector('[data-kr-admin-remove]');
      if (remove) { remove.hidden = !removable; }

      // The first line cannot move up and the last cannot move down, so those
      // controls are hidden rather than offered and then refused.
      var up = row.querySelector('[data-kr-admin-up]');
      var down = row.querySelector('[data-kr-admin-down]');
      if (up) { up.hidden = index === 0; }
      if (down) { down.hidden = index === all.length - 1; }
    });
    if (addButton) { addButton.hidden = all.length >= MAX_ROWS; }
  }

  /**
   * Move one line past its neighbour and renumber every field, so the array
   * the server receives is in the order on the screen. Focus follows the line
   * that moved, otherwise a keyboard user loses their place on every press.
   */
  function move(row, direction) {
    var neighbour = direction < 0 ? row.previousElementSibling : row.nextElementSibling;
    if (!neighbour) { return; }
    if (direction < 0) {
      host.insertBefore(row, neighbour);
    } else {
      host.insertBefore(neighbour, row);
    }
    renumber();

    var button = row.querySelector(direction < 0 ? '[data-kr-admin-up]' : '[data-kr-admin-down]');
    if (button && !button.hidden) {
      button.focus();
      return;
    }
    var name = row.querySelector('input[name*="[item_name]"]');
    if (name) { name.focus(); }
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
    var up = event.target.closest('[data-kr-admin-up]');
    if (up) { move(up.closest('[data-kr-admin-row]'), -1); return; }

    var down = event.target.closest('[data-kr-admin-down]');
    if (down) { move(down.closest('[data-kr-admin-row]'), 1); return; }

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
