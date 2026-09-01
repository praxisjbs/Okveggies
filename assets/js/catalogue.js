/** Progressive enhancements for catalogue filters, the live shop search and add controls. */
(function () {
  'use strict';

  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function focusable(root) {
    return Array.prototype.filter.call(root.querySelectorAll(FOCUSABLE), function (el) {
      return el.offsetWidth > 0 || el.offsetHeight > 0;
    });
  }

  /** Keep Tab inside the sheet while it is open. It claims aria-modal, so it
      has to behave like one for a keyboard and a screen reader. */
  function trapTab(sheet, event) {
    if (event.key !== 'Tab') { return; }
    var items = focusable(sheet);
    if (items.length === 0) { return; }
    var first = items[0];
    var last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    } else if (!sheet.contains(document.activeElement)) {
      event.preventDefault();
      first.focus();
    }
  }

  function setSheet(open) {
    var sheet = document.querySelector('[data-filter-sheet]');
    var opener = document.querySelector('[data-filter-open]');
    if (!sheet) { return; }
    sheet.hidden = !open;
    document.body.style.overflow = open ? 'hidden' : '';
    if (opener) { opener.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    if (open) {
      var close = sheet.querySelector('[data-filter-close]');
      if (close) { close.focus(); }
    } else if (opener) {
      opener.focus();
    }
  }

  function enhanceAddForm(form) {
    form.addEventListener('submit', function (event) {
      if (!window.fetch) { return; }
      event.preventDefault();
      var button = form.querySelector('[data-add-button]');
      if (!button || button.disabled) { return; }
      var original = button.textContent;
      button.disabled = true;
      button.textContent = 'Adding';

      fetch(form.getAttribute('action'), {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
      }).then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (data) {
          if (!response.ok || data.status === 'error') {
            throw new Error(data.message || 'We could not add that item. Please try again.');
          }
          document.querySelectorAll('.okv-basket-count').forEach(function (count) {
            count.textContent = data.basket_count;
            // Market Bounce, 320ms: the counter increment is one of the three
            // moments the brand allows the accent curve (bible 6.5). Removing
            // the class first lets a second add replay the animation.
            count.classList.remove('animate-okv-pop');
            void count.offsetWidth;
            count.classList.add('animate-okv-pop');
            window.setTimeout(function () { count.classList.remove('animate-okv-pop'); }, 400);
          });
          document.querySelectorAll('a[href="/cart.php"]').forEach(function (link) {
            link.setAttribute('aria-label', 'Basket, ' + data.basket_count + ' items');
          });
          button.textContent = 'Added';
          button.classList.add('animate-okv-pop');
          if (window.OKV && OKV.toast) { OKV.toast(data.message, 'ok'); }
          window.setTimeout(function () {
            button.textContent = original;
            button.disabled = false;
            button.classList.remove('animate-okv-pop');
          }, 1200);
        });
      }).catch(function (error) {
        button.textContent = original;
        button.disabled = false;
        if (window.OKV && OKV.toast) { OKV.toast(error.message, 'error'); }
      });
    });
  }

  /**
   * The skeleton the results column shows while the next page of produce is
   * on the way (bible 6.7). It is built from the same card shape the real
   * grid uses, so the column keeps its height and the page does not jump when
   * the answer lands. prefers-reduced-motion collapses the pulse site-wide
   * through the stylesheet, so there is nothing to branch on here.
   */
  function skeletonGrid(count) {
    var wrap = document.createElement('div');

    var status = document.createElement('p');
    status.className = 'sr-only';
    status.setAttribute('role', 'status');
    status.textContent = 'Loading produce';
    wrap.appendChild(status);

    var head = document.createElement('div');
    head.className = 'mb-6 hidden items-end justify-between gap-4 lg:flex';
    head.setAttribute('aria-hidden', 'true');
    var headLeft = document.createElement('div');
    headLeft.className = 'okv-skeleton h-8 w-48';
    var headRight = document.createElement('div');
    headRight.className = 'okv-skeleton h-4 w-32';
    head.appendChild(headLeft);
    head.appendChild(headRight);
    wrap.appendChild(head);

    var grid = document.createElement('div');
    grid.className = 'grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4';
    grid.setAttribute('aria-hidden', 'true');
    for (var i = 0; i < count; i++) {
      var card = document.createElement('article');
      card.className = 'okv-card';
      ['okv-skeleton aspect-square w-full',
       'okv-skeleton mt-3 h-4 w-3/4',
       'okv-skeleton mt-2 h-3 w-1/2',
       'okv-skeleton mt-4 h-11 w-full'].forEach(function (cls) {
        var bar = document.createElement('div');
        bar.className = cls;
        card.appendChild(bar);
      });
      grid.appendChild(card);
    }
    wrap.appendChild(grid);
    return wrap;
  }

  /**
   * The live shop search. Typing into the box asks the server for the page of
   * results (debounced) and swaps the results column with the exact markup a
   * fresh load of the same URL would render: same cards, same pagination,
   * same empty state. Without JavaScript the form still submits and filters
   * the same way, so this changes nothing about what works, only how quickly.
   */
  function liveShopSearch(container) {
    if (!container) { return; }
    var input = document.getElementById('shop-search');
    if (!input || !window.fetch || !window.AbortController) { return; }

    var form = input.form;
    var sheetSearch = document.querySelector('[data-sheet-search]');
    var fromUrl = new URLSearchParams(window.location.search);
    var state = {
      search: (fromUrl.get('search') || '').trim(),
      category: fromUrl.get('category') || ''
    };
    var timer = null;
    var controller = null;
    // The last results markup that actually rendered. A failed search puts
    // this back, so the customer never ends up staring at a stalled skeleton.
    // It is not read from the container at request time, because by then the
    // container may already be showing the skeleton of a superseded search.
    var lastGood = container.innerHTML;

    function shopUrl(page) {
      var params = new URLSearchParams();
      if (state.search !== '') { params.set('search', state.search); }
      if (state.category !== '') { params.set('category', state.category); }
      if (page > 1) { params.set('page', page); }
      var query = params.toString();
      return '/shop.php' + (query ? '?' + query : '');
    }

    function browse(page, push) {
      if (controller) { controller.abort(); }
      controller = new AbortController();
      container.setAttribute('aria-busy', 'true');

      // Keep the column roughly the height it already is, so the skeleton
      // reads as the same page loading rather than the page collapsing.
      var showing = container.querySelectorAll('[data-product-card]').length;
      container.innerHTML = '';
      container.appendChild(skeletonGrid(Math.min(12, Math.max(4, showing))));

      var api = '/api/v1/catalog.php?action=browse&search=' + encodeURIComponent(state.search)
        + '&category=' + encodeURIComponent(state.category)
        + '&page=' + page;

      fetch(api, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        signal: controller.signal
      }).then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (data) {
          return { ok: response.ok, data: data };
        });
      }).then(function (result) {
        if (!result.ok || result.data.status !== 'ok' || typeof result.data.html !== 'string') {
          throw new Error('browse failed');
        }
        var data = result.data;
        container.innerHTML = data.html;
        lastGood = data.html;
        container.removeAttribute('aria-busy');
        if (push) { window.history.pushState(null, '', shopUrl(data.page)); }
        else { window.history.replaceState(null, '', shopUrl(data.page)); }
        document.querySelectorAll('[data-shop-summary]').forEach(function (node) {
          node.textContent = data.summary;
        });
        // The category chips and the sidebar carry the search term in their
        // links; keep them pointing at what is actually typed, or the next
        // click would quietly drop it.
        document.querySelectorAll('[data-shop-category-link]').forEach(function (node) {
          var cat = node.getAttribute('data-shop-category-link') || '';
          var params = new URLSearchParams();
          if (state.search !== '') { params.set('search', state.search); }
          if (cat !== '') { params.set('category', cat); }
          var query = params.toString();
          node.setAttribute('href', '/shop.php' + (query ? '?' + query : ''));
        });
        // Disabled when empty so the sheet submits /shop.php?category=fruit
        // rather than trailing an empty search= behind it.
        if (sheetSearch) {
          sheetSearch.value = state.search;
          sheetSearch.disabled = state.search === '';
        }
        container.querySelectorAll('[data-add-form]').forEach(enhanceAddForm);
      }).catch(function (error) {
        if (error && error.name === 'AbortError') { return; }
        container.innerHTML = lastGood;
        container.removeAttribute('aria-busy');
        container.querySelectorAll('[data-add-form]').forEach(enhanceAddForm);
        if (window.OKV && OKV.toast) {
          OKV.toast('We could not search right now. Check your connection and try again.', 'error');
        }
      });
    }

    input.addEventListener('input', function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(function () {
        state.search = input.value.trim();
        browse(1, false);
      }, 300);
    });

    if (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        window.clearTimeout(timer);
        state.search = input.value.trim();
        browse(1, true);
      });
    }

    // Pagination inside the results runs without a reload when the listener
    // is up; the links themselves stay plain GET anchors for everywhere else.
    document.addEventListener('click', function (event) {
      var link = event.target && event.target.closest ? event.target.closest('[data-pagination] a') : null;
      if (!link || !container.contains(link)) { return; }
      var href = link.getAttribute('href') || '';
      if (href.indexOf('/shop.php') !== 0) { return; }
      event.preventDefault();
      var page = parseInt(new URLSearchParams(href.split('?')[1] || '').get('page'), 10) || 1;
      browse(page, true);
    });

    window.addEventListener('popstate', function () {
      var params = new URLSearchParams(window.location.search);
      state.search = (params.get('search') || '').trim();
      state.category = params.get('category') || '';
      input.value = state.search;
      browse(parseInt(params.get('page'), 10) || 1, false);
    });
  }

  function ready() {
    var opener = document.querySelector('[data-filter-open]');
    var sheet = document.querySelector('[data-filter-sheet]');
    if (opener) { opener.addEventListener('click', function () { setSheet(true); }); }
    if (sheet) {
      var close = sheet.querySelector('[data-filter-close]');
      if (close) { close.addEventListener('click', function () { setSheet(false); }); }
      sheet.addEventListener('click', function (event) {
        if (event.target === sheet) { setSheet(false); }
      });
      document.addEventListener('keydown', function (event) {
        if (sheet.hidden) { return; }
        if (event.key === 'Escape') { setSheet(false); return; }
        trapTab(sheet, event);
      });
    }
    document.querySelectorAll('[data-add-form]').forEach(enhanceAddForm);
    liveShopSearch(document.querySelector('[data-shop-results]'));
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ready);
  } else {
    ready();
  }
})();
