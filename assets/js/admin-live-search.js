/**
 * assets/js/admin-live-search.js
 * -----------------------------------------------------------------------------
 * OK Veggies. The auto-fill search bar on the back-office lists: Payments,
 * Customers and Kitchen Runs today, and any screen that marks up the same four
 * hooks tomorrow.
 *
 * One box, two answers while a colleague types. The results region below it
 * narrows from the first character (PRD 5.6: live, debounced at 300ms), and a
 * suggestion listbox opens under the box with the names and numbers that
 * match, so the box can be filled from what the caller actually said rather
 * than retyped from a row. Typing the second character cancels the request the
 * first one started, which is the whole feel of it: one character to two,
 * never a stale answer left on screen.
 *
 * The server renders the results markup (api/v1/<module>.php, action browse)
 * with the same component a plain load of the same URL uses, so what someone
 * sees while typing is what a reload shows. The two cannot drift.
 *
 * Committing (Enter, a suggestion, the Search button) is still a plain GET
 * form submit, so the screens that open a record on one match keep doing it,
 * and everything still works with JavaScript switched off. The address bar
 * keeps up with replaceState while typing and pushState on a committed page
 * turn, so a filtered list stays shareable without a history entry per
 * keystroke.
 *
 * Hooks, all on or inside one form:
 *   [data-live-form]      the GET form. Carries the config:
 *                           data-live-endpoint  api url, action browse
 *                           data-live-param     the query key the term rides in
 *                           data-live-page      the screen path, for URLs
 *                           data-live-keep      space separated query keys to
 *                                               carry through (an open record)
 *                           data-live-min       characters before we ask (1)
 *                           data-live-debounce  ms of quiet before we ask (300)
 *   [data-live-input]     the box. Becomes an ARIA 1.2 combobox.
 *   [data-live-state]     sibling filters (a status tab, an account type) that
 *                         ride along on every request and every URL.
 *   [data-live-results]   the region swapped with the server markup.
 *   [data-live-summary]   optional count line kept in step with the results.
 *   [data-live-status]    optional visually hidden role=status line.
 *
 * Results arrive as markup from our own endpoint and go in with one
 * assignment, the way admin-products.js does it. Everything a customer typed
 * into a suggestion is written with textContent, never innerHTML.
 * -----------------------------------------------------------------------------
 */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  function toast(message, type) {
    if (window.OKV && OKV.toast) { OKV.toast(message, type); }
  }

  function init(form) {
    var input   = form.querySelector('[data-live-input]');
    var results = document.querySelector('[data-live-results]');
    if (!input || !results || !window.fetch || !window.AbortController) { return; }

    var summary  = document.querySelector('[data-live-summary]');
    var status   = document.querySelector('[data-live-status]');
    var endpoint = form.getAttribute('data-live-endpoint') || '';
    var param    = form.getAttribute('data-live-param') || 'search';
    var pagePath = form.getAttribute('data-live-page') || window.location.pathname;
    var keep     = (form.getAttribute('data-live-keep') || '').split(/\s+/).filter(Boolean);
    var minChars = parseInt(form.getAttribute('data-live-min') || '1', 10) || 1;
    var debounce = parseInt(form.getAttribute('data-live-debounce') || '300', 10) || 300;
    if (!endpoint) { return; }

    // --- The suggestion listbox, ARIA 1.2 combobox with list autocomplete. ---
    var listId  = (input.id || 'okv-live-search') + '-suggestions';
    var listbox = document.createElement('ul');
    listbox.id = listId;
    listbox.setAttribute('role', 'listbox');
    listbox.className = 'absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-md border border-mist bg-white shadow-md';
    listbox.hidden = true;
    if (input.parentNode) {
      input.parentNode.classList.add('relative');
      input.parentNode.appendChild(listbox);
    }
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', listId);
    input.setAttribute('aria-expanded', 'false');

    var timer      = null;
    var controller = null;
    var options    = [];
    var active     = -1;

    function announce(text) {
      if (status) { status.textContent = text; }
    }

    function stateFields() {
      var fields = [];
      form.querySelectorAll('[data-live-state]').forEach(function (field) {
        if (field.name && field.value !== '') { fields.push({ name: field.name, value: field.value }); }
      });
      return fields;
    }

    function keepParams() {
      var current = new URLSearchParams(window.location.search);
      return keep.map(function (key) {
        return { name: key, value: current.get(key) || '' };
      }).filter(function (pair) { return pair.value !== ''; });
    }

    function buildQuery(term, page, forApi) {
      var params = new URLSearchParams();
      if (forApi) { params.set('action', 'browse'); }
      if (term !== '') { params.set(param, term); }
      stateFields().forEach(function (field) { params.set(field.name, field.value); });
      keepParams().forEach(function (pair) { params.set(pair.name, pair.value); });
      if (page > 1) { params.set('page', String(page)); }
      return params.toString();
    }

    function pageUrl(term, page) {
      var query = buildQuery(term, page, false);
      return pagePath + (query ? '?' + query : '');
    }

    // --- Suggestions ---------------------------------------------------------
    function closeList() {
      listbox.hidden = true;
      listbox.textContent = '';
      options = [];
      active = -1;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
    }

    function setActive(index) {
      var nodes = listbox.querySelectorAll('[role="option"]');
      for (var i = 0; i < nodes.length; i++) {
        var on = i === index;
        nodes[i].setAttribute('aria-selected', on ? 'true' : 'false');
        nodes[i].classList.toggle('bg-forest-tint', on);
      }
      active = index;
      if (index >= 0 && nodes[index]) {
        input.setAttribute('aria-activedescendant', nodes[index].id);
      } else {
        input.removeAttribute('aria-activedescendant');
      }
    }

    function choose(value) {
      input.value = value;
      closeList();
      window.clearTimeout(timer);
      if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
    }

    function renderSuggestions(list) {
      listbox.textContent = '';
      options = list || [];
      if (!options.length || input.value.trim() === '') { closeList(); return; }

      options.forEach(function (item, index) {
        var option = document.createElement('li');
        option.id = listId + '-option-' + index;
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', 'false');
        option.className = 'min-h-[44px] cursor-pointer px-3 py-2 text-sm hover:bg-forest-tint';

        var label = document.createElement('span');
        label.className = 'block font-medium text-ink';
        label.textContent = item.label;
        option.appendChild(label);

        if (item.sub) {
          var sub = document.createElement('span');
          sub.className = 'block text-xs text-ink-60';
          sub.textContent = item.sub;
          option.appendChild(sub);
        }

        option.addEventListener('mousedown', function (event) {
          event.preventDefault();
          choose(item.value);
        });
        listbox.appendChild(option);
      });

      listbox.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      setActive(0);
    }

    // --- The live results ------------------------------------------------------
    function load(page, push) {
      var term = input.value.trim();
      if (term !== '' && term.length < minChars) { return; }
      if (controller) { controller.abort(); }
      controller = new AbortController();
      results.setAttribute('aria-busy', 'true');

      fetch(endpoint + '?' + buildQuery(term, page, true), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        signal: controller.signal
      }).then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (data) {
          return { ok: response.ok, data: data || {} };
        });
      }).then(function (res) {
        controller = null;
        results.removeAttribute('aria-busy');
        if (!res.ok || res.data.status !== 'ok' || typeof res.data.html !== 'string') {
          throw new Error((res.data && res.data.message) || 'browse failed');
        }
        results.innerHTML = res.data.html;
        if (summary && typeof res.data.summary === 'string') { summary.textContent = res.data.summary; }
        announce(res.data.summary || (term === '' ? '' : 'No matches'));
        renderSuggestions(res.data.suggestions || []);
        var url = pageUrl(term, page);
        if (push) { window.history.pushState(null, '', url); }
        else { window.history.replaceState(null, '', url); }
      }).catch(function (error) {
        if (error && error.name === 'AbortError') { return; }
        controller = null;
        results.removeAttribute('aria-busy');
        announce('We could not load that list. Check your connection and try again.');
        toast('We could not load that list. Check your connection and try again.', 'error');
      });
    }

    function schedule() {
      window.clearTimeout(timer);
      var term = input.value.trim();
      if (term !== '' && term.length < minChars) { closeList(); return; }
      timer = window.setTimeout(function () { load(1, false); }, debounce);
    }

    input.addEventListener('input', schedule);

    input.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        if (listbox.hidden) {
          if (input.value.trim().length >= minChars) { load(1, false); }
          return;
        }
        event.preventDefault();
        var next = event.key === 'ArrowDown' ? active + 1 : active - 1;
        if (next >= options.length) { next = 0; }
        if (next < 0) { next = options.length - 1; }
        setActive(next);
        return;
      }
      if (event.key === 'Enter') {
        // A highlighted suggestion wins over the half-typed term, then the
        // form submits natively with what the box now holds.
        window.clearTimeout(timer);
        if (!listbox.hidden && active >= 0 && options[active]) {
          input.value = options[active].value;
          closeList();
        }
        return;
      }
      if (event.key === 'Escape' && !listbox.hidden) {
        event.preventDefault();
        closeList();
      }
    });

    input.addEventListener('blur', function () {
      window.setTimeout(closeList, 120);
    });

    form.addEventListener('submit', function () {
      window.clearTimeout(timer);
      closeList();
    });

    // Page turns inside the swapped region stay on the page. Row links and
    // anything else keep their native behaviour.
    results.addEventListener('click', function (event) {
      var link = event.target && event.target.closest ? event.target.closest('[data-pagination] a') : null;
      if (!link || !results.contains(link)) { return; }
      var href = link.getAttribute('href') || '';
      if (href.indexOf(pagePath) !== 0) { return; }
      event.preventDefault();
      var page = parseInt(new URLSearchParams(href.split('?')[1] || '').get('page'), 10) || 1;
      window.clearTimeout(timer);
      load(page, true);
    });

    window.addEventListener('popstate', function () {
      var params = new URLSearchParams(window.location.search);
      input.value = (params.get(param) || '').trim();
      form.querySelectorAll('[data-live-state]').forEach(function (field) {
        if (field.name) { field.value = params.get(field.name) || ''; }
      });
      closeList();
      load(parseInt(params.get('page'), 10) || 1, false);
    });
  }

  ready(function () {
    document.querySelectorAll('[data-live-form]').forEach(init);
  });
})();
