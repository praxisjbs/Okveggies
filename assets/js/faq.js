/** Optional FAQ bulk controls. Native details work when this file does not. */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  ready(function () {
    var items = Array.prototype.slice.call(document.querySelectorAll('[data-faq-item]'));
    var expand = document.querySelector('[data-faq-expand]');
    var collapse = document.querySelector('[data-faq-collapse]');
    var status = document.querySelector('[data-faq-status]');
    if (!items.length || !expand || !collapse) { return; }
    var controls = document.querySelector('[data-faq-controls]');
    if (controls) { controls.classList.remove('hidden'); controls.classList.add('flex'); }

    function updateStatus() {
      if (!status) { return; }
      var open = items.filter(function (item) { return item.open; }).length;
      status.textContent = open + ' of ' + items.length + ' answers open.';
    }
    function setAll(open) {
      items.forEach(function (item) { item.open = open; });
      updateStatus();
    }

    expand.addEventListener('click', function () { setAll(true); });
    collapse.addEventListener('click', function () { setAll(false); });
    items.forEach(function (item) { item.addEventListener('toggle', updateStatus); });
    updateStatus();
  });
})();
