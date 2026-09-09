/**
 * assets/js/admin-credit.js
 * OK Veggies. Turns the repayment select on /admin/credit.php into a
 * type-to-filter box.
 *
 * The select is the real control and works on its own with JavaScript off. This
 * only puts a filter in front of it, because a busy account can carry more
 * payments than anyone wants to scroll. Nothing here talks to the server: the
 * options are already on the page, and the form posts the same field either way.
 */
(function () {
  'use strict';

  /** Escape nothing into the DOM. Every value goes in as text. */
  function option(payment, id, index) {
    var li = document.createElement('li');
    li.id = id + '-option-' + index;
    li.setAttribute('role', 'option');
    li.setAttribute('aria-selected', 'false');
    li.className = 'flex min-h-[44px] cursor-pointer items-center px-3 py-2 text-sm hover:bg-forest-tint';
    li.textContent = payment.label;
    li.dataset.value = payment.value;
    return li;
  }

  function enhance(form) {
    var select = form.querySelector('select[name="payment_id"]');
    if (!select || select.options.length < 2 || select.dataset.okvEnhanced === '1') { return; }
    select.dataset.okvEnhanced = '1';

    var id = select.id || 'okv-repay-' + Math.random().toString(36).slice(2, 8);
    var payments = Array.prototype.map.call(select.options, function (opt) {
      return { value: opt.value, label: opt.textContent.replace(/\s+/g, ' ').trim() };
    });

    // The select carries the value and stays in the form. It is hidden from
    // sight but not from the form, so a submit still sends payment_id.
    select.hidden = true;
    select.setAttribute('aria-hidden', 'true');
    select.tabIndex = -1;

    var wrap = document.createElement('div');
    wrap.className = 'relative';

    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'okv-input';
    input.id = id + '-filter';
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', id + '-listbox');
    input.setAttribute('aria-autocomplete', 'list');
    input.autocomplete = 'off';
    input.placeholder = 'Type to find a payment';
    input.value = payments[0].label;

    var list = document.createElement('ul');
    list.id = id + '-listbox';
    list.setAttribute('role', 'listbox');
    list.className = 'absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-md border border-mist bg-white shadow-md';
    list.hidden = true;

    var label = form.querySelector('label[for="' + select.id + '"]');
    if (label) { label.setAttribute('for', input.id); }

    wrap.appendChild(input);
    wrap.appendChild(list);
    select.parentNode.insertBefore(wrap, select);

    var active = -1;
    var shown = [];

    function close() {
      list.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      active = -1;
    }

    function choose(index) {
      if (index < 0 || index >= shown.length) { return; }
      select.value = shown[index].value;
      input.value = shown[index].label;
      close();
    }

    function highlight(index) {
      var items = list.querySelectorAll('[role="option"]');
      for (var i = 0; i < items.length; i++) {
        var on = i === index;
        items[i].setAttribute('aria-selected', on ? 'true' : 'false');
        items[i].classList.toggle('bg-forest-tint', on);
        if (on) { input.setAttribute('aria-activedescendant', items[i].id); }
      }
      active = index;
    }

    function render(term) {
      var needle = term.toLowerCase();
      shown = payments.filter(function (payment) {
        return needle === '' || payment.label.toLowerCase().indexOf(needle) !== -1;
      });
      list.textContent = '';
      if (shown.length === 0) {
        var empty = document.createElement('li');
        empty.className = 'px-3 py-2 text-sm text-ink-60';
        empty.textContent = 'No payment matches that.';
        list.appendChild(empty);
      } else {
        shown.forEach(function (payment, index) {
          var li = option(payment, id, index);
          li.addEventListener('mousedown', function (event) {
            event.preventDefault();
            choose(index);
          });
          list.appendChild(li);
        });
      }
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      highlight(shown.length ? 0 : -1);
    }

    input.addEventListener('input', function () { render(input.value); });
    input.addEventListener('focus', function () { render(''); });
    input.addEventListener('blur', function () { window.setTimeout(close, 120); });

    input.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        if (list.hidden) { render(input.value); return; }
        var next = event.key === 'ArrowDown' ? active + 1 : active - 1;
        if (next >= shown.length) { next = 0; }
        if (next < 0) { next = shown.length - 1; }
        highlight(next);
      } else if (event.key === 'Enter') {
        if (!list.hidden && active >= 0) {
          event.preventDefault();
          choose(active);
        }
      } else if (event.key === 'Escape') {
        close();
      }
    });

    // Whatever happens above, the server only ever sees the select's value, so
    // a typed value that matches nothing cannot be submitted.
    form.addEventListener('submit', function () { close(); });
  }

  function init() {
    var forms = document.querySelectorAll('[data-okv-credit-repayment]');
    Array.prototype.forEach.call(forms, enhance);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // The manage panels sit inside <details>, so a form can appear after load.
  document.addEventListener('toggle', function (event) {
    if (event.target && event.target.tagName === 'DETAILS' && event.target.open) { init(); }
  }, true);
}());
