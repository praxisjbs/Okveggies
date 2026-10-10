/**
 * assets/js/admin-orders.js
 * -----------------------------------------------------------------------------
 * OK Veggies. The staff order list (admin/orders.php).
 *
 * From 1280px up (xl) the screen is two columns: the list on the left, the
 * open order on the right. This file leaves that alone. Below 1280px the two
 * columns stack, so tapping an order used to push the list far up the page and
 * leave the reader scrolling to find the detail. Here the detail opens as a
 * sheet over the list instead:
 *
 *   1. The server still renders one order's detail into the right column. On a
 *      narrow screen that column is taken out of the flow at once, so the list
 *      is all that shows. If the page was opened on a specific order (a deep
 *      link, or a redirect back from an action), that detail is moved into the
 *      sheet and the sheet is opened.
 *   2. Tapping another order fetches the same ?order= URL, lifts the rendered
 *      detail out of the response and drops it into the sheet. Every form in
 *      the detail still posts and redirects exactly as it did inline, because
 *      it is the same markup; data-once (okv.js) is delegated on document, so
 *      it keeps working on the injected forms.
 *   3. It behaves like a dialog. While open the panel carries role and
 *      aria-modal, Tab is kept inside it, Escape closes it, the page behind is
 *      locked, and the phone Back button closes it rather than leaving the
 *      screen. Focus returns to wherever it was.
 *
 * Everything it drives is server-rendered. With JavaScript off, a tap is a
 * plain link to ?order=, the detail stacks under the list as before, and the
 * desktop two-column layout is untouched either way.
 * -----------------------------------------------------------------------------
 */
(function () {
  'use strict';

  var sheet = document.getElementById('order-sheet');
  var panel = sheet ? sheet.querySelector('[data-order-panel]') : null;
  var body = document.getElementById('order-sheet-body');
  var closeButton = sheet ? sheet.querySelector('[data-order-close]') : null;
  var column = document.querySelector('[data-order-detail]');
  if (!sheet || !panel || !body) { return; }

  // Below xl (1280px). Matches the grid breakpoint the markup switches on.
  var narrow = window.matchMedia('(max-width: 1279px)');

  var open = false;
  var ownsEntry = false; // the history entry this sheet pushed is still ours
  var opener = null;     // what had focus when the sheet opened

  function lockPage(on) {
    // Both elements, or iOS Safari still scrolls the page behind a fixed sheet.
    document.documentElement.style.overflow = on ? 'hidden' : '';
    document.body.style.overflow = on ? 'hidden' : '';
  }

  function focusable() {
    return Array.prototype.slice.call(
      panel.querySelectorAll(
        'a[href], button:not([disabled]), input:not([type=hidden]):not([disabled]),' +
        ' select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      )
    ).filter(function (item) {
      return !item.hidden && item.offsetParent !== null;
    });
  }

  // On a narrow screen the inline column is never shown. Take it out of the flow
  // and empty it, so its ids can never collide with the copy in the sheet.
  function clearColumn() {
    if (column) { column.hidden = true; column.innerHTML = ''; }
  }

  function skeleton() {
    return '<div class="space-y-3 p-5">' +
      '<div class="h-6 w-1/3 animate-pulse rounded bg-mist" aria-hidden="true"></div>' +
      '<div class="h-4 w-2/3 animate-pulse rounded bg-mist" aria-hidden="true"></div>' +
      '<div class="h-28 w-full animate-pulse rounded bg-mist" aria-hidden="true"></div>' +
      '<div class="h-4 w-1/2 animate-pulse rounded bg-mist" aria-hidden="true"></div>' +
      '<p class="sr-only" role="status">Loading the order</p>' +
      '</div>';
  }

  function errorHtml(href) {
    return '<div class="p-5 text-sm">' +
      '<p class="okv-note bg-clay-tint" role="alert">The order could not be loaded. ' +
      '<a class="underline" href="' + encodeURI(href) + '">Open it on its own page</a>.</p>' +
      '</div>';
  }

  function reveal() {
    if (open) { return; }
    open = true;
    opener = document.activeElement;
    sheet.hidden = false;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    lockPage(true);
    panel.scrollTop = 0;
    panel.focus({ preventScroll: true });
  }

  function hide(entryAlreadyPopped) {
    if (!open) { return false; }
    open = false;
    sheet.hidden = true;
    panel.removeAttribute('role');
    panel.removeAttribute('aria-modal');
    lockPage(false);
    body.innerHTML = ''; // never leave the injected ids behind in the DOM
    if (opener && typeof opener.focus === 'function' && document.contains(opener)) {
      opener.focus();
    }
    opener = null;
    var spend = ownsEntry;
    ownsEntry = false;
    if (spend && !entryAlreadyPopped) {
      window.history.back();
      return true;
    }
    return false;
  }

  function loadInto(href) {
    body.innerHTML = skeleton();
    panel.scrollTop = 0;
    window.fetch(href, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch' }
    }).then(function (res) {
      if (!res.ok) { throw new Error('status ' + res.status); }
      return res.text();
    }).then(function (html) {
      var doc = new DOMParser().parseFromString(html, 'text/html');
      var detail = doc.querySelector('[data-order-detail]');
      if (!detail) { throw new Error('no detail in response'); }
      body.innerHTML = detail.innerHTML;
      panel.scrollTop = 0;
    }).catch(function () {
      body.innerHTML = errorHtml(href);
    });
  }

  function openForHref(href) {
    clearColumn();
    reveal();
    try {
      window.history.pushState({ okvOrderSheet: true }, '', href);
      ownsEntry = true;
    } catch (error) {
      ownsEntry = false;
    }
    loadInto(href);
  }

  // The page arrived on a narrow screen. Never let the server-rendered detail
  // stack under the list: move it into the sheet if a real order is open, or
  // drop it if the list is all that was asked for.
  function initNarrow() {
    if (!narrow.matches) { return; }
    var hasOrder = new URLSearchParams(window.location.search).get('order');
    var rendered = column && column.querySelector('#order-detail-heading');
    if (hasOrder && rendered) {
      var markup = column.innerHTML;
      clearColumn();
      reveal();
      body.innerHTML = markup;
      panel.scrollTop = 0;
      // No history entry: the current URL already is this order.
    } else {
      clearColumn();
    }
  }

  initNarrow();

  if (closeButton) {
    closeButton.addEventListener('click', function () { hide(false); });
  }

  // A tap on the dimmed area outside the panel closes the sheet.
  sheet.addEventListener('click', function (event) {
    if (event.target === sheet) { hide(false); }
  });

  document.addEventListener('keydown', function (event) {
    if (!open) { return; }
    if (event.key === 'Escape') {
      event.preventDefault();
      hide(false);
      return;
    }
    if (event.key !== 'Tab') { return; }
    var items = focusable();
    if (items.length === 0) {
      event.preventDefault();
      panel.focus();
      return;
    }
    var first = items[0];
    var last = items[items.length - 1];
    var inside = panel.contains(document.activeElement);
    if (event.shiftKey && (document.activeElement === first || !inside)) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && (document.activeElement === last || !inside)) {
      event.preventDefault();
      first.focus();
    }
  });

  // Phone Back closes the sheet instead of leaving the screen.
  window.addEventListener('popstate', function () {
    if (open) { hide(true); }
  });

  // A page restored from the back/forward cache comes back as it was left, an
  // open sheet and a locked page included, so put it back to rest.
  window.addEventListener('pageshow', function (event) {
    if (!event.persisted) { return; }
    if (open) { hide(true); } else { lockPage(false); }
  });

  // A tap on an order, while narrow. Desktop keeps the plain navigation that
  // fills the right column.
  document.addEventListener('click', function (event) {
    if (!narrow.matches || event.defaultPrevented || event.button !== 0) { return; }
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) { return; }
    var link = event.target && event.target.closest ? event.target.closest('a[data-order-link]') : null;
    if (!link || (link.target && link.target !== '_self')) { return; }
    event.preventDefault();
    openForHref(link.href);
  });

  // Crossing up into desktop width hands the layout back to the two columns.
  function onWidthChange() {
    if (!narrow.matches && open) { hide(false); }
  }
  if (typeof narrow.addEventListener === 'function') {
    narrow.addEventListener('change', onWidthChange);
  } else if (typeof narrow.addListener === 'function') {
    narrow.addListener(onWidthChange);
  }
}());
