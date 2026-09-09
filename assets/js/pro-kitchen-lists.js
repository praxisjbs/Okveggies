/** Progressive row controls for saved Kitchen List forms. */
(function () {
  'use strict';

  var MAX_ROWS = 50;

  function rows(host) {
    return Array.prototype.slice.call(host.querySelectorAll('[data-kl-row]'));
  }

  function renumber(form) {
    var host = form.querySelector('[data-kl-rows]');
    if (!host) { return; }
    rows(host).forEach(function (row, index) {
      row.querySelectorAll('[name]').forEach(function (field) {
        field.name = field.name.replace(/items\[\d+\]/, 'items[' + index + ']');
      });
      row.querySelectorAll('[id]').forEach(function (field) {
        var oldId = field.id;
        var fresh = oldId.replace(/-\d+$/, '-' + index);
        var label = row.querySelector('label[for="' + oldId + '"]');
        field.id = fresh;
        if (label) { label.setAttribute('for', fresh); }
      });
    });
    var count = rows(host).length;
    var add = form.querySelector('[data-kl-add]');
    if (add) { add.hidden = count >= MAX_ROWS; }
    rows(host).forEach(function (row) {
      var remove = row.querySelector('[data-kl-remove]');
      if (remove) { remove.hidden = count <= 1; }
    });
  }

  document.querySelectorAll('[data-kl-form]').forEach(function (form) {
    var host = form.querySelector('[data-kl-rows]');
    var add = form.querySelector('[data-kl-add]');
    if (!host || !add) { return; }

    add.hidden = false;
    add.addEventListener('click', function () {
      var all = rows(host);
      if (!all.length || all.length >= MAX_ROWS) { return; }
      var clone = all[all.length - 1].cloneNode(true);
      clone.querySelectorAll('input').forEach(function (field) { field.value = ''; });
      clone.querySelectorAll('select').forEach(function (field) { field.selectedIndex = 0; });
      host.appendChild(clone);
      renumber(form);
      var name = clone.querySelector('[data-kl-name]');
      if (name) { name.focus(); }
    });

    host.addEventListener('click', function (event) {
      var remove = event.target.closest('[data-kl-remove]');
      if (!remove || rows(host).length <= 1) { return; }
      remove.closest('[data-kl-row]').remove();
      renumber(form);
    });

    host.addEventListener('change', function (event) {
      var select = event.target.closest('[data-kl-product]');
      if (!select || !select.value) { return; }
      var option = select.options[select.selectedIndex];
      var row = select.closest('[data-kl-row]');
      var name = row.querySelector('[data-kl-name]');
      var unit = row.querySelector('[data-kl-unit]');
      if (name) { name.value = option.getAttribute('data-name') || ''; }
      if (unit) { unit.value = option.getAttribute('data-unit') || ''; }
    });

    renumber(form);
  });
}());
