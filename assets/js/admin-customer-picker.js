/**
 * assets/js/admin-customer-picker.js
 * -----------------------------------------------------------------------------
 * OK Veggies. Live customer search for the back-office screens that start a
 * piece of work: the phone order and the typed-in kitchen list.
 *
 * Both screens find the caller the same way, so the search lives here once
 * rather than in each of them. It is enhancement only. Both screens carry a
 * plain GET search form that works with JavaScript switched off, and this
 * replaces the round trip with a typed one, because a page load between "what
 * is your name" and "found you" is a silence on a phone call.
 *
 * It attaches itself to any screen carrying [data-customer-search] beside a
 * [data-customer-results] box. Results are written with textContent, never
 * innerHTML: a customer's own name is not markup.
 * -----------------------------------------------------------------------------
 */
(function () {
  'use strict';

  var box     = document.querySelector('[data-customer-search]');
  var results = document.querySelector('[data-customer-results]');
  if (!box || !results) { return; }

  var timer = null;

  function render(customers, term) {
    results.textContent = '';

    if (!customers.length) {
      var none = document.createElement('p');
      none.className = 'mt-4 rounded-md border border-clay bg-clay-tint px-3 py-2 text-sm text-ink';
      none.setAttribute('role', 'status');
      none.textContent = 'Nobody matches ' + term + '. Add them below.';
      results.appendChild(none);
      return;
    }

    var list = document.createElement('ul');
    list.className = 'mt-4 divide-y divide-mist';

    customers.forEach(function (customer) {
      var item = document.createElement('li');
      item.className = 'flex flex-wrap items-center justify-between gap-3 py-3';

      var about = document.createElement('div');

      var name = document.createElement('p');
      name.className = 'font-medium text-ink';
      name.textContent = customer.name;

      var detail = document.createElement('p');
      detail.className = 'text-sm text-ink-60';
      detail.textContent = customer.phone_display
        + ' . ' + customer.orders + (customer.orders === 1 ? ' order' : ' orders')
        + ' . ' + customer.type;

      about.appendChild(name);
      about.appendChild(detail);

      var choose = document.createElement('a');
      choose.className = 'okv-btn-outline-sm inline-flex items-center';
      choose.href = '?user_id=' + encodeURIComponent(customer.id);
      choose.textContent = 'Choose';

      item.appendChild(about);
      item.appendChild(choose);
      list.appendChild(item);
    });

    results.appendChild(list);
  }

  /**
   * A read, but a POST, because api/v1/customers.php gates every action on the
   * CSRF token and one door is easier to keep shut than two.
   */
  function search() {
    var term = box.value.trim();
    if (term.length < 2) { return; }

    var body = new URLSearchParams();
    body.set('action', 'search');
    body.set('search', term);
    body.set('okv_csrf', (window.OKV && window.OKV.csrf) || '');

    fetch('/api/v1/customers.php', {
      method: 'POST',
      headers: {
        'X-Requested-With': 'fetch',
        'Accept': 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      credentials: 'same-origin',
      body: body.toString()
    })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (data && data.status === 'ok') { render(data.customers || [], term); }
      })
      .catch(function () { /* The GET form on the page still works. Say nothing. */ });
  }

  box.addEventListener('input', function () {
    window.clearTimeout(timer);
    timer = window.setTimeout(search, 250);
  });
})();
