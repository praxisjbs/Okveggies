/**
 * assets/js/admin-run-new.js
 * -----------------------------------------------------------------------------
 * OK Veggies. Progressive enhancement for the typed-in kitchen list screen.
 *
 * The screen is a plain HTML form and works with JavaScript switched off: ten
 * line slots post natively and the server reads catalogue names, units and
 * prices itself. Nothing here is required to save a list.
 *
 * What this adds is what transcribing somebody else's list actually needs:
 *
 *   1. More lines than the ten the server drew, because a restaurant list runs
 *      to twenty items and a colleague should not have to save it twice.
 *   2. The written-out name and the unit fade back on a line that has taken a
 *      shop item, since the catalogue already carries both and typing a second
 *      name over it only invites the two to disagree.
 *
 * Finding the customer is admin-customer-picker.js, shared with the phone-order
 * screen.
 *
 * No arithmetic here at all. A typed-in list is unpriced by definition: prices
 * go on it next, in the quote workshop, where the running total already lives.
 * -----------------------------------------------------------------------------
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-run-form]');
  if (!form) { return; }

  var host = form.querySelector('[data-run-rows]');
  var addButton = form.querySelector('[data-run-add]');
  if (!host) { return; }

  var MAX_ROWS = 100;

  function rows() {
    return Array.prototype.slice.call(host.querySelectorAll('[data-run-row]'));
  }

  /**
   * A line that has taken a shop item already has a name and a unit from the
   * catalogue, so the written-out name goes quiet rather than inviting a second
   * name that disagrees with the first.
   */
  function reflect(row) {
    var product = row.querySelector('[data-run-product]');
    var name    = row.querySelector('[data-run-name]');
    if (!product || !name) { return; }

    var fromShop = product.value !== '';
    name.disabled = fromShop;
    name.placeholder = fromShop ? 'Taken from the shop' : 'Pomo';
    if (fromShop) { name.value = ''; }
  }

  /** Field names carry their index, so a cloned line has to be renumbered. */
  function renumber() {
    rows().forEach(function (row, index) {
      row.querySelectorAll('[name]').forEach(function (field) {
        field.name = field.name.replace(/items\[\d+\]/, 'items[' + index + ']');
      });
      row.querySelectorAll('[id]').forEach(function (field) {
        var fresh = field.id.replace(/run-\d+-/, 'run-' + index + '-');
        var label = row.querySelector('label[for="' + field.id + '"]');
        field.id = fresh;
        if (label) { label.setAttribute('for', fresh); }
      });
      var legend = row.querySelector('legend');
      if (legend) { legend.textContent = 'Line ' + (index + 1); }
    });
  }

  host.addEventListener('change', function (event) {
    var row = event.target.closest('[data-run-row]');
    if (row && event.target.matches('[data-run-product]')) { reflect(row); }
  });

  if (addButton) {
    addButton.hidden = false;
    addButton.classList.remove('hidden');
    addButton.addEventListener('click', function () {
      var all = rows();
      if (all.length >= MAX_ROWS) { return; }

      var fresh = all[all.length - 1].cloneNode(true);
      fresh.querySelectorAll('input').forEach(function (field) {
        field.value = '';
        field.disabled = false;
      });
      fresh.querySelectorAll('select').forEach(function (field) { field.selectedIndex = 0; });

      host.appendChild(fresh);
      renumber();
      reflect(fresh);

      var first = fresh.querySelector('[data-run-product]');
      if (first) { first.focus(); }
    });
  }

  rows().forEach(reflect);
})();
